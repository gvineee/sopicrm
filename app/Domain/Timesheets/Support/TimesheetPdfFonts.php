<?php

namespace App\Domain\Timesheets\Support;

use Illuminate\Support\Facades\File;

/**
 * TIMESHEET-01: dompdf ships no Georgian-capable font, and never falls back
 * to one — an unregistered 'NotoSansGeorgian' font-family in the PDF's CSS
 * silently renders as empty boxes with no exception (verified: this is
 * exactly what happened before this class existed). `storage/fonts/NotoSansGeorgian-{Regular,Bold}.ttf`
 * are the real, glyph-complete (U+10A0-U+10FF, both weights confirmed via
 * fontTools' cmap during this ticket) Noto Sans Georgian static TTFs,
 * extracted once from the `@fontsource/noto-sans-georgian` npm package's
 * WOFF2 files (decompressed with `fonttools`) — that npm package itself was
 * never added as a project dependency, only used as a one-time source.
 *
 * `Dompdf\FontMetrics::registerFont()` is idempotent (early-returns once a
 * style's local cache file already matches), so calling this on every PDF
 * render is safe and correctly self-heals in any environment (fresh clone,
 * CI, a wiped `storage/fonts` cache) without needing the generated
 * hashed `.ttf`/`.ufm`/`installed-fonts.json` cache artifacts themselves to
 * be committed — only the two named source TTFs are (see
 * `storage/fonts/.gitignore`).
 */
class TimesheetPdfFonts
{
    public const FAMILY = 'NotoSansGeorgian';

    public static function register(): void
    {
        $regular = storage_path('fonts/NotoSansGeorgian-Regular.ttf');
        $bold = storage_path('fonts/NotoSansGeorgian-Bold.ttf');

        if (! File::exists($regular) || ! File::exists($bold)) {
            throw new \RuntimeException(
                'Georgian PDF font missing at storage/fonts/NotoSansGeorgian-{Regular,Bold}.ttf — see TimesheetPdfFonts docblock.'
            );
        }

        $fontMetrics = app('dompdf.wrapper')->getFontMetrics();

        $fontMetrics->registerFont(
            ['family' => self::FAMILY, 'style' => 'normal', 'weight' => 'normal'],
            'file://'.str_replace('\\', '/', $regular),
        );

        $fontMetrics->registerFont(
            ['family' => self::FAMILY, 'style' => 'normal', 'weight' => 'bold'],
            'file://'.str_replace('\\', '/', $bold),
        );
    }
}
