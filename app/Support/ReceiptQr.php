<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\URL;

class ReceiptQr
{
    public static function verifyUrl(string $receiptNo): string
    {
        return URL::signedRoute('receipts.verify', ['receipt_no' => $receiptNo]);
    }

    public static function dataUri(string $payload): string
    {
        $options = new QROptions;
        $options->outputInterface = QRMarkupSVG::class;
        $options->outputBase64 = true;
        $options->eccLevel = EccLevel::M;
        $options->addQuietzone = true;
        $options->quietzoneSize = 4;
        $options->svgAddXmlHeader = false;
        $options->drawLightModules = false;
        $options->connectPaths = true;
        $options->scale = 5;

        return (new QRCode($options))->addByteSegment($payload)->render();
    }
}
