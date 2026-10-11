#!/bin/bash
# アップロード: SSO のユーザが Encoder を通して動画を投稿できるか。
# 実際に動画を変換するので、他のシナリオより時間がかかる。
#
# 設定は、ネイティブログインを許可リストに絞り、Encoder のアドレスだけを例外にする
# （Encoder は投稿者本人としてネイティブログインするので、例外が無いと投稿できない）。
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
    # Encoder は、状態の問い合わせを受けたときにキューの処理を始める。ふだんは、開いている
    # Encoder の画面が定期的に問い合わせている。ここでは代わりに問い合わせる。
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
# 動画のディレクトリを消す。名前が空のまま rm -rf すると videos/ ごと消える（AVideo の設定ファイルも
# そこにある）ので、名前が取れていて、動画のディレクトリの形をしているときだけ消す。
case "$filename" in
    ''|*[!A-Za-z0-9_.-]*|.|..) ;;
    *) dexec "$AVIDEO_CONTAINER" rm -rf "/var/www/html/AVideo/videos/$filename" ;;
esac
reset_settings
finish
