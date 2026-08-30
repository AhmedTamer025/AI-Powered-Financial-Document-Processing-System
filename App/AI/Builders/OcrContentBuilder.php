<?php
namespace App\AI\Builders;

use App\AI\DTOS\RawDocument;

class OcrContentBuilder{

public static function build(RawDocument $document): string {

    $content = '';

    foreach ($document->sections as $section) {

        $page = ($section['page'] ?? 0) + 1;

        $markdown = trim(
            $section['markdown'] ?? ''
        );

        if ($markdown === '') {
            continue;
        }

        $content .= PHP_EOL;

$content .= str_repeat('=', 40) . PHP_EOL;

$content .= "PAGE {$page}" . PHP_EOL;

$content .= str_repeat('=', 40) . PHP_EOL;

$content .= $markdown;

$content .= PHP_EOL;
    }

    return trim($content);

}
}
