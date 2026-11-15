<?php

declare(strict_types=1);

namespace App\Services;

use GdImage;
use RuntimeException;

/**
 * Subida y procesado de imágenes.
 *
 * El punto importante: la imagen NO se guarda como llega. Se decodifica con
 * GD y se vuelve a codificar desde cero. Eso destruye todo lo que viaje
 * fuera de los píxeles — EXIF con PHP adentro, comentarios con una shell,
 * archivos poliglotas que son GIF y PHP a la vez. Lo que queda en disco es
 * un JPEG o un WebP escrito por nosotros, no el archivo del usuario.
 *
 * El nombre también lo ponemos nosotros, derivado del hash: el nombre
 * original del archivo nunca llega al sistema de archivos.
 */
final class ImageProcessor
{
    /** Anchos que se generan de cada imagen. */
    public const SIZES = [400, 800, 1600];

    private const MAX_BYTES = 12 * 1024 * 1024;
    private const MAX_PIXELS = 50_000_000;  // freno a la bomba de descompresión

    private const ACCEPTED = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
        'image/gif' => true,
    ];

    public function __construct(private readonly string $uploadDir)
    {
    }

    /**
     * @param array $file una entrada de $_FILES
     * @return array{filename:string,mime:string,bytes:int,width:int,height:int,sha256:string}
     */
    public function store(array $file): array
    {
        $this->assertUploadOk($file);

        return $this->storeFromPath((string) $file['tmp_name']);
    }

    /**
     * Mismo procesado, para un archivo que ya está en disco.
     *
     * Lo usa el importador de WordPress: las imágenes viejas pasan por
     * exactamente la misma validación y el mismo re-codificado que una
     * subida nueva. Nada entra por una puerta de atrás.
     */
    public function storeFromPath(string $tmp): array
    {
        if (!is_file($tmp) || !is_readable($tmp)) {
            throw new RuntimeException('No se puede leer el archivo.');
        }

        if (filesize($tmp) > self::MAX_BYTES) {
            throw new RuntimeException('La imagen supera el tamaño máximo.');
        }

        // El tipo se decide con finfo sobre el contenido real. Lo que diga
        // la extensión o el navegador no se mira: ambos los controla quien sube.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);

        if (!isset(self::ACCEPTED[$mime])) {
            throw new RuntimeException('Formato no admitido. Solo JPEG, PNG, WebP o GIF.');
        }

        $info = @getimagesize($tmp);
        if ($info === false) {
            throw new RuntimeException('El archivo no es una imagen válida.');
        }

        [$width, $height] = $info;

        // Un archivo que empieza con la firma de un GIF pero sigue con
        // cualquier cosa pasa el finfo y devuelve dimensiones basura. Se
        // corta acá, con un mensaje que diga lo que realmente pasa.
        if ($width < 1 || $height < 1 || $width > 20000 || $height > 20000) {
            throw new RuntimeException('El archivo dice ser una imagen pero no lo es.');
        }

        // Bomba de descompresión: pocos bytes, miles de millones de píxeles.
        if ($width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('La imagen tiene demasiados píxeles.');
        }

        $source = $this->decode($tmp, $mime);
        $sha = hash_file('sha256', $tmp);
        $base = substr($sha, 0, 32);

        // PHP 8.5 marca imagedestroy() como obsoleta: desde 8.0 los GdImage
        // son objetos y el recolector los libera solo.
        {
            // Original normalizado: re-codificado y con un tope de tamaño.
            $main = $this->resize($source, max(self::SIZES));
            $filename = $base . '.jpg';
            $this->writeJpeg($main, $this->uploadDir . '/' . $filename, 82);

            $outWidth = imagesx($main);
            $outHeight = imagesy($main);

            // Variantes para srcset.
            foreach (self::SIZES as $size) {
                if ($size >= $outWidth) {
                    continue;
                }
                $variant = $this->resize($source, $size);
                $this->writeJpeg($variant, $this->uploadDir . '/' . $base . '-' . $size . '.jpg', 80);
            }
        }

        return [
            'filename' => $filename,
            'mime' => 'image/jpeg',
            'bytes' => (int) filesize($this->uploadDir . '/' . $filename),
            'width' => $outWidth,
            'height' => $outHeight,
            'sha256' => $sha,
        ];
    }

    /** Borra el archivo y todas sus variantes. */
    public function delete(string $filename): void
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        if (!preg_match('/^[a-f0-9]{32}$/', $base)) {
            return;  // nombre que no generamos nosotros: no se toca
        }

        @unlink($this->uploadDir . '/' . $base . '.jpg');
        foreach (self::SIZES as $size) {
            @unlink($this->uploadDir . '/' . $base . '-' . $size . '.jpg');
        }
    }

    /** srcset listo para la plantilla. */
    public static function srcset(string $filename, int $width): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $parts = [];

        foreach (self::SIZES as $size) {
            if ($size < $width) {
                $parts[] = "/uploads/{$base}-{$size}.jpg {$size}w";
            }
        }

        $parts[] = "/uploads/{$base}.jpg {$width}w";

        return implode(', ', $parts);
    }

    // ------------------------------------------------------------------

    private function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('El archivo pesa más de lo que admite el servidor.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo subir el archivo.');
        }

        // Sin esto, un atacante podría pasar una ruta arbitraria del servidor
        // y hacer que la leamos como si fuera lo que acaba de subir.
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new RuntimeException('Origen de archivo no válido.');
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('La imagen no puede superar los 12 MB.');
        }
    }

    private function decode(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }

        return $image;
    }

    private function resize(GdImage $source, int $maxWidth): GdImage
    {
        $w = imagesx($source);
        $h = imagesy($source);

        if ($w <= $maxWidth) {
            $target = imagecreatetruecolor($w, $h);
            $this->flatten($target, $w, $h);
            imagecopy($target, $source, 0, 0, 0, 0, $w, $h);

            return $target;
        }

        $newW = $maxWidth;
        $newH = (int) max(1, round($h * ($maxWidth / $w)));

        $target = imagecreatetruecolor($newW, $newH);
        $this->flatten($target, $newW, $newH);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newW, $newH, $w, $h);

        return $target;
    }

    /** Fondo blanco: el JPEG no tiene transparencia. */
    private function flatten(GdImage $image, int $w, int $h): void
    {
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $w, $h, $white);
    }

    private function writeJpeg(GdImage $image, string $path, int $quality): void
    {
        imageinterlace($image, true);  // progresivo: se ve antes de terminar

        if (!imagejpeg($image, $path, $quality)) {
            throw new RuntimeException('No se pudo escribir la imagen procesada.');
        }

        chmod($path, 0640);
    }
}
