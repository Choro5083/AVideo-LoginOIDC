#!/bin/bash
# Runs every end-to-end scenario. See lib.sh for the environment variables.
#
# Requires the development environment to be up, with Keycloak freshly recreated
# (the last scenario, 07-sync.sh, changes and deletes Keycloak users).
#
# AVideo's site language must be English (en_US): 01 and 06 check English messages. The scenarios
# also assume this order. 07-sync.sh leaves dave disabled in AVideo, so running 01-login.sh again
# afterwards fails until dave is enabled again.
cd "$(dirname "$0")" || exit 1
failed=0
for scenario in 0[1-7]-*.sh; do
    echo "=== $scenario"
    bash "$scenario" || failed=$((failed + 1))
done
[ "$failed" -eq 0 ] && echo "ALL PASSED" || echo "FAILED SCENARIOS: $failed"
exit "$failed"
