#!/bin/bash
# Keycloak sync.
# It changes and deletes users at Keycloak. Recreate the Keycloak container afterwards to restore them.
source "$(dirname "$0")/lib.sh"
reset_settings
for user in alice bob carol dave; do sso_login $user "$WORK/$user" >/dev/null; done

echo "nothing changed at the provider"
out=$(run_sync)
contains "sync succeeds" "exit=0" "$out"
contains "and disables nobody" "disabled 0" "$out"

echo "changes at the provider"
kc -X PUT -d '{"enabled":false}' "$KC_ADMIN/users/$CAROL" -o /dev/null
kc -X DELETE "$KC_ADMIN/users/$DAVE" -o /dev/null
kc -X DELETE "$KC_ADMIN/users/$BOB/groups/$(kc_group_id avideo-uploaders)" -o /dev/null
kc -X PUT -d '{"firstName":"Robert","lastName":"Viewer"}' "$KC_ADMIN/users/$BOB" -o /dev/null
kc -X PUT "$KC_ADMIN/users/$ALICE/groups/$(kc_group_id avideo-uploaders)" -o /dev/null

out=$(run_sync --dry-run)
contains "dry-run reports who would be disabled" "disabled: carol (disabled at the provider)" "$out"
contains "dry-run says a real run would stop" "a real run would stop" "$out"
check "dry-run changes nothing" a "$(user_field carol status)"

out=$(run_sync)
contains "2 of 4 is over the 20% limit, so the sync refuses" "refusing to disable 2 of 4" "$out"
contains "with a failure exit code" "exit=1" "$out"
check "and changes nothing" a "$(user_field carol status)"

out=$(run_sync --max-disable-ratio=50)
contains "with the limit raised, the sync runs" "exit=0" "$out"
check "carol (disabled at the provider) is disabled" i "$(user_field carol status)"
check "dave (deleted at the provider) is disabled, not deleted" i "$(user_field dave status)"
check "bob lost the upload permission" 0 "$(user_field bob canUpload)"
check "bob's name was updated" "Robert Viewer" "$(user_field bob name)"
check "alice gained the upload permission" 1 "$(user_field alice canUpload)"
check "alice kept the admin permission" 1 "$(user_field alice isAdmin)"
check "carol's session ended" no "$(logged_in "$WORK/carol")"
check "dave's session ended" no "$(logged_in "$WORK/dave")"
check "alice's session continues" yes "$(logged_in "$WORK/alice")"
check "bob's session continues" yes "$(logged_in "$WORK/bob")"

echo "re-enabling"
db "update users set status='i' where user='bob'"   # as if an admin had disabled the user by hand
kc -X PUT -d '{"enabled":true}' "$KC_ADMIN/users/$CAROL" -o /dev/null
out=$(run_sync)
contains "carol is re-enabled" "re-enabled: carol" "$out"
check "carol is active again" a "$(user_field carol status)"
check "bob, disabled by hand, stays disabled" i "$(user_field bob status)"
db "update users set status='a' where user='bob'"
sso_login carol "$WORK/carol2" >/dev/null
check "carol can sign in again" yes "$(logged_in "$WORK/carol2")"

echo "failures change nothing"
kc -X PUT -d '{"enabled":false}' "$KC_ADMIN/users/$CAROL" -o /dev/null
out=$(SYNC_SECRET=wrong run_sync --max-disable-ratio=100)
contains "a wrong secret fails" "exit=1" "$out"
contains "and says nothing was changed" "nothing was changed" "$out"
check "carol is still active" a "$(user_field carol status)"
check "a bad option is a usage error" "exit=2" "$(run_sync --max-disable-ratio=abc | tail -1)"
check "the CLI is not reachable over HTTP" 404 "$(curl -sk -o /dev/null -w '%{http_code}' "$AVIDEO_URL/plugin/LoginOIDC/cli/sync.php")"
kc -X PUT -d '{"enabled":true}' "$KC_ADMIN/users/$CAROL" -o /dev/null
reset_settings
echo "NOTE: Keycloak was modified (dave is deleted). Recreate the keycloak container before running the tests again."
finish
