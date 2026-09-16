<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PdfBinaryResponse
{
    public static function fromHtml(
        string $html,
        string $filename,
        string $paper = 'A4',
        string $orientation = 'landscape',
    ): Response {
        @ini_set('memory_limit', '512M');
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        try {
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isFontSubsettingEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');
            $chroot = public_path();
            if (is_dir($chroot)) {
                $options->setChroot($chroot);
            }

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper($paper, $orientation);
            $dompdf->render();
            $output = $dompdf->output();
        } catch (Throwable $e) {
            report($e);

            abort(response()->json([
                'message' => 'Unable to generate the PDF. Try Excel or Word, or narrow the filters.',
            ], 422));
        }

        if ($output === '' || ! str_starts_with($output, '%PDF')) {
            abort(response()->json([
                'message' => 'Unable to generate the PDF. Try Excel or Word, or narrow the filters.',
            ], 422));
        }

        $safe = preg_replace('/[^A-Za-z0-9_\-.]+/', '_', $filename) ?: 'report';
        if (! str_ends_with(strtolower($safe), '.pdf')) {
            $safe .= '.pdf';
        }

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$safe.'"',
            'Content-Length' => (string) strlen($output),
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
