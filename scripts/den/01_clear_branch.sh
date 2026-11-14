#!/usr/bin/env bash
# Safely clear ONLY the branch «хуй»; save a full local Git bundle first.
set -Eeuo pipefail

REPO_URL="https://github.com/Dum-senior/digital-challenge-hackathon.git"
BRANCH='хуй'
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

fail() { printf '\nОШИБКА: %s\n' "$*" >&2; exit 1; }
command -v git >/dev/null 2>&1 || fail "Git не найден. Установите Git for Windows."

printf '\nРепозиторий: %s\nВетка для очистки: %s\n' "$REPO_URL" "$BRANCH"
printf 'Важно: удаляются только отслеживаемые файлы В ЭТОЙ ВЕТКЕ. main не изменяется.\n'
printf 'Перед очисткой будет создан резервный .bundle в папке со скриптом.\n\n'
read -r -p 'Чтобы продолжить, введите CLEAN (другой ответ отменит): ' CONFIRM
[[ "$CONFIRM" == "CLEAN" ]] || fail "Операция отменена."

TMP="$(mktemp -d)" || fail "Не удалось создать временную папку."
[[ -n "$TMP" && "$TMP" != / ]] || fail "Неверная временная папка."
cleanup() { if [[ -n "${TMP:-}" && "$TMP" != / && -d "$TMP" ]]; then rm -rf -- "$TMP"; fi; }
trap cleanup EXIT

printf '\n1/4: Читаю именно ветку «%s»...\n' "$BRANCH"
git clone --quiet --single-branch --branch "$BRANCH" "$REPO_URL" "$TMP/work" \
  || fail "Не удалось клонировать ветку. Проверьте интернет и доступ к GitHub."
cd "$TMP/work"
[[ "$(git branch --show-current)" == "$BRANCH" ]] || fail "Проверка ветки не прошла."
[[ -z "$(git status --porcelain)" ]] || fail "Найдено неожиданное локальное изменение; остановка."
ORIGINAL_SHA="$(git rev-parse HEAD)"

printf '2/4: Сохраняю копию коммитов и содержимого...\n'
STAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP="$HERE/backup_huy_${STAMP}_${ORIGINAL_SHA:0:8}.bundle"
git bundle create "$BACKUP" "refs/heads/$BRANCH" >/dev/null \
  || fail "Не удалось создать резервную копию — очистка отменена."
git bundle verify "$BACKUP" >/dev/null 2>&1 \
  || fail "Резервная копия не прошла проверку — очистка отменена."
printf 'Резервная копия: %s\n' "$BACKUP"

if [[ -z "$(git ls-files)" ]]; then
  printf '\nВетка уже пустая. Ничего менять не нужно.\n'
  exit 0
fi

# The name/email are used ONLY for this isolated temporary clone.
if [[ -z "$(git config user.name || true)" ]]; then
  git config user.name "Dispatcher Git Script"
fi
if [[ -z "$(git config user.email || true)" ]]; then
  git config user.email "dispatcher-script@users.noreply.github.com"
fi

printf '3/4: Удаляю отслеживаемые файлы из временной копии ветки...\n'
git rm --quiet -r -- .
git commit --quiet -m "chore(dispatcher): clear temporary branch before full import"

printf '4/4: Обычный push БЕЗ --force. Если ветка изменилась — GitHub отклонит push.\n'
git push origin "HEAD:refs/heads/$BRANCH" \
  || fail "GitHub отклонил push. Никакого force push не выполнялось; резервная копия сохранена: $BACKUP"

printf '\nУСПЕХ: ветка «%s» очищена. main и другие ветки не затронуты.\n' "$BRANCH"
printf 'Резервная копия: %s\n' "$BACKUP"
printf 'Теперь запустите второй скрипт: bash 02_push_dispatcher.sh\n'
