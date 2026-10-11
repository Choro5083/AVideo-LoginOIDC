#!/bin/bash
# Upload: can a user signed in through the provider post a video through the Encoder?
# It really encodes a video, so it takes longer than the other scenarios.
#
# Native sign-in is restricted to the allowlist, with only the Encoder's address exempt
# (the Encoder signs in natively as the uploader, so without the exemption nothing can be posted).
source "$(dirname "$0")/lib.sh"
reset_settings
TITLE="e2e-upload-$$"
enc_json() { python3 -c "import json,sys
try: print(json.load(sys.stdin).get('$1'))
except Exception: print('unreadable')"; }
video_field() { db "select $1 from videos where title='$TITLE' order by id desc limit 1"; }

sso_login bob "$WORK/bob" >/dev/null
check "bob is signed in and can upload" 1 "$(user_field bob canUpload)"
setting nativeLoginMode allowlist; setting nativeLoginAllowlist admin; setting encoderAddresses "127.0.0.1"

echo "Encoder sign-in (AVideo hands the Encoder a time-limited token for the signed-in user)"
TOKEN=$(user_token bob)
login=$(curl -sk -c "$WORK/enc" -b "$WORK/enc" --data-urlencode user=bob --data-urlencode "pass=$TOKEN" \
    --data-urlencode "siteURL=$AVIDEO_URL/" --data-urlencode encodedPass=1 "$AVIDEO_URL/Encoder/login")
check "the Encoder accepts bob" True "$(echo "$login" | enc_json isLogged)"
check "and sees the upload permission" True "$(echo "$login" | enc_json canUpload)"

echo "upload"
dexec "$AVIDEO_CONTAINER" ffmpeg -loglevel error -y -f lavfi -i testsrc=duration=5:size=640x360:rate=30 \
    -f lavfi -i sine=frequency=440:duration=5 -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest /tmp/e2e-source.mp4
$SUDO docker cp "$AVIDEO_CONTAINER:/tmp/e2e-source.mp4" "$WORK/source.mp4" >/dev/null
upload=$(curl -sk -c "$WORK/enc" -b "$WORK/enc" -F "upl=@$WORK/source.mp4;type=video/mp4" -F "title=$TITLE" \
    -F description=e2e -F inputAutoMP4=true "$AVIDEO_URL/Encoder/upload")
contains "the Encoder queues the file" '"error":false' "$(echo "$upload" | tr -d ' ')"

echo "waiting for the Encoder to convert and deliver (up to 4 minutes)"
for _ in $(seq 1 48); do
    [ "$(video_field status)" = a ] && break
    # The Encoder starts working on its queue when its status is requested. Normally an open
    # Encoder page does that periodically. Here the test does it instead.
    curl -sk -b "$WORK/enc" -o /dev/null "$AVIDEO_URL/Encoder/status"
    sleep 5
done
check "the video arrived at AVideo and is active" a "$(video_field status)"
check "it belongs to bob, not to a service account" bob "$(db "select u.user from videos v join users u on u.id=v.users_id where v.title='$TITLE' order by v.id desc limit 1")"
filename=$(video_field filename)
files=$(dexec "$AVIDEO_CONTAINER" bash -c "ls /var/www/html/AVideo/videos/$filename/ 2>/dev/null | grep -c '\.mp4$'")
check "at least one converted file exists" yes "$([ "${files:-0}" -ge 1 ] && echo yes || echo no)"
check "the watch page is served" 200 "$(curl -sk -o /dev/null -w '%{http_code}' -b "$WORK/bob" "$AVIDEO_URL/video/$(video_field id)")"

echo "a viewer cannot upload"
sso_login carol "$WORK/carol" >/dev/null
login=$(curl -sk -c "$WORK/enc2" -b "$WORK/enc2" --data-urlencode user=carol --data-urlencode "pass=$(user_token carol)" \
    --data-urlencode "siteURL=$AVIDEO_URL/" --data-urlencode encodedPass=1 "$AVIDEO_URL/Encoder/login")
check "the Encoder does not give carol the upload permission" False "$(echo "$login" | enc_json canUpload)"

db "delete from videos where title='$TITLE'" 2>/dev/null
dexec "$AVIDEO_CONTAINER" rm -f /tmp/e2e-source.mp4
# Remove the video's directory. rm -rf with an empty name would delete all of videos/ (where AVideo's
# configuration file lives too), so it runs only when the name was found and looks like a video directory.
case "$filename" in
    ''|*[!A-Za-z0-9_.-]*|.|..) ;;
    *) dexec "$AVIDEO_CONTAINER" rm -rf "/var/www/html/AVideo/videos/$filename" ;;
esac
reset_settings
finish
