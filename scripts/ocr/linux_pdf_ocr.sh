#!/usr/bin/env bash
set -euo pipefail

PDF="${1:-}"

if [ -z "$PDF" ] || [ ! -f "$PDF" ]; then
  echo "Usage: linux_pdf_ocr.sh /chemin/fichier.pdf" >&2
  exit 1
fi

DPI="${AI_PTA_OCR_DPI:-150}"
LANGS="${AI_PTA_OCR_LANG:-fra+eng}"
PSM="${AI_PTA_OCR_PSM:-6}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

PAGES="$(pdfinfo "$PDF" 2>/dev/null | awk '/^Pages:/ {print $2}')"

if [ -z "$PAGES" ]; then
  echo "Impossible de déterminer le nombre de pages du PDF." >&2
  exit 1
fi

for PAGE in $(seq 1 "$PAGES"); do
  PREFIX="$TMP_DIR/page_${PAGE}"

  pdftoppm -f "$PAGE" -l "$PAGE" -r "$DPI" -png "$PDF" "$PREFIX" >/dev/null 2>&1 || continue

  IMG="$(find "$TMP_DIR" -maxdepth 1 -type f -name "page_${PAGE}*.png" | head -n 1)"

  if [ -z "$IMG" ] || [ ! -f "$IMG" ]; then
    continue
  fi

  tesseract "$IMG" stdout -l "$LANGS" --psm "$PSM" tsv 2>/dev/null | \
  awk -v page="$PAGE" '
    BEGIN { FS="\t" }

    NR > 1 && $1 == 5 && $12 != "" && $11 != "-1" {
      key = page ":" $3 ":" $4 ":" $5

      if (!(key in seen)) {
        seen[key] = 1
        order[++count] = key
        minx[key] = $7
        miny[key] = $8
        text[key] = $12
      } else {
        if ($7 < minx[key]) minx[key] = $7
        if ($8 < miny[key]) miny[key] = $8
        text[key] = text[key] " " $12
      }
    }

    END {
      for (i = 1; i <= count; i++) {
        key = order[i]
        clean = text[key]
        gsub(/\|/, " ", clean)
        gsub(/"/, "", clean)
        printf("@@OCR_BOX|%s|%s|%s|%s\n", page, minx[key], miny[key], clean)
      }
    }
  '
done
