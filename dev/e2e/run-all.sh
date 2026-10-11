#!/bin/bash
# 結合テストをすべて流す。環境変数は lib.sh を参照。
#
# 前提: 開発環境が起動していて、Keycloak が作り直した直後の状態であること
# （05-sync.sh が Keycloak のユーザを変更・削除するため）。
cd "$(dirname "$0")" || exit 1
failed=0
for scenario in 0[1-5]-*.sh; do
    echo "=== $scenario"
    bash "$scenario" || failed=$((failed + 1))
done
[ "$failed" -eq 0 ] && echo "ALL PASSED" || echo "FAILED SCENARIOS: $failed"
exit "$failed"
