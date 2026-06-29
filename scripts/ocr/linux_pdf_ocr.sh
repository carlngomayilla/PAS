#!/usr/bin/env bash
set -euo pipefail

PDF="${1:-}"

if [ -z "$PDF" ] || [ ! -f "$PDF" ]; then
  echo "Usage: linux_pdf_ocr.sh /chemin/fichier.pdf" >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

pdftoppm -r 300 -png "$PDF" "$TMP_DIR/page" >/dev/null 2>&1

for IMG in "$TMP_DIR"/page-*.png; do
  [ -f "$IMG" ] || continue

  BASENAME="$(basename "$IMG")"
  PAGE="$(echo "$BASENAME" | sed -E 's/page-([0-9]+)\.png/\1/')"

  tesseract "$IMG" stdout -l fra+eng --psm 6 tsv 2>/dev/null | \
  awk -v page="$PAGE" '
    BEGIN { FS="\t" }

    NR > 1 && $1 == 5 && $12 != "" && $11 != "-1" {
      key = page ":" $3 ":" $4 ":" $5

      if (!(key in seen)) {
        seen[key] = 1
        order[++count] = key
        minx[key] = $7
        miny[key] = $8
        maxx[key] = $7 + $9
        maxy[key] = $8 + $10
        text[key] = $12
        conf[key] = $11
        words[key] = 1
      } else {
        if ($7 < minx[key]) minx[key] = $7
        if ($8 < miny[key]) miny[key] = $8
        if (($7 + $9) > maxx[key]) maxx[key] = $7 + $9
        if (($8 + $10) > maxy[key]) maxy[key] = $8 + $10
        text[key] = text[key] " " $12
        conf[key] += $11
        words[key] += 1
      }
    }

    END {
      for (i = 1; i <= count; i++) {
        key = order[i]
        clean = text[key]
        gsub(/"/, "\\\"", clean)
        avg = int(conf[key] / words[key])
        printf("@@OCR_BOX page=%s x=%s y=%s w=%s h=%s conf=%s text=\"%s\"\n", page, minx[key], miny[key], maxx[key]-minx[key], maxy[key]-miny[key], avg, clean)
      }
    }
  '
done
