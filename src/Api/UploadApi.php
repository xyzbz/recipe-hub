<?php
declare(strict_types=1);

namespace RH\Api;

use RH\Images;
use RH\Http;

final class UploadApi
{
    public static function upload(): void
    {
        $file = $_FILES['file'] ?? null;

        if (!is_array($file)) {
            Http::fail('没有收到文件', 400, 'file');
        }

        $info = Images::upload($file);

        Http::ok(array(
            'path'   => $info['path'],
            'thumb'  => $info['thumb'],
            'url'    => Images::url($info['path']),
            'thumb_url' => Images::thumbUrl($info['path']),
            'width'  => $info['width'],
            'height' => $info['height'],
            'size'   => $info['size'],
            'human'  => Images::humanSize($info['size']),
        ));
    }
}
