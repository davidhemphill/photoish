<?php

require_once __DIR__.'/../vendor/autoload.php';

try {
    (new Dotenv\Dotenv(__DIR__.'/../'))->load();
} catch (Dotenv\Exception\InvalidPathException $e) {
    //
}

$app = new Laravel\Lumen\Application(
    realpath(__DIR__.'/../')
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->get('/', function () use ($app) {
    return 'Photoish service up';
});

$app->get('/i', function () use ($app) {
    $client = Aws\S3\S3Client::factory([
        'credentials' => [
            'key'    => getenv('S3_KEY'),
            'secret' => getenv('S3_SECRET'),
        ],
        'region' => getenv('S3_REGION'),
        'version' => 'latest',
    ]);

    $local = new League\Flysystem\Filesystem(new League\Flysystem\Adapter\Local(storage_path('app/images/')));
    $server = League\Glide\ServerFactory::create([
        'source' => $source = new League\Flysystem\Filesystem(new League\Flysystem\AwsS3v3\AwsS3Adapter($client, getenv('S3_BUCKET'), 'source')),
        'cache' => $cache = new League\Flysystem\Filesystem(new League\Flysystem\AwsS3v3\AwsS3Adapter($client, getenv('S3_BUCKET'), 'cache')),
    ]);

    $filename = md5(app('request')->fullUrl());

    // If this file isn't cached, download it 
    // and store it in the S3 source
    if (! $cache->has($filename)) {
        // Download the image
        $ch = curl_init(app('request')->get('image'));
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
        $raw = curl_exec($ch);
        curl_close($ch);

        // Write the raw data to the filesystem
        $local->write($filename, $raw);

        // Read the temp image
        $image = $local->read($filename);

        // Store it in S3
        $source->put($filename, $raw, ['visibility' => 'public']);

        // Delete the tmp image
        $local->delete($filename);
    }

    // Output the image to the browser
    return $server->outputImage($source->get($filename)->getPath(), app('request')->intersect([
        'image', 'w', 'h', 'fit', 'crop', 'rect', 'or', 'bri', 'con',
        'gam', 'sharp', 'blur', 'pixel', 'filt', 'q', 'fm'
    ]));
});

$app->run();
