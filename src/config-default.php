<?php
return [

    'securityToken' => 'a-very-secure-token-that-no-one-knows',

    'retrieverMap' => [
        $_SERVER['HTTP_HOST'] => $_SERVER['DOCUMENT_ROOT']
    ],

    'cache' => [
        'cacheRoot' => sys_get_temp_dir() . '/phast-cache-' . posix_geteuid(),
        'cacheTTL' => 86400 * 365
    ],

    'documents' => [
        'maxBufferSizeToApply' => pow(1024, 3),

        'baseUrl' => (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
            . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],

        'filters' => [

            \Kibo\Phast\Filters\HTML\ScriptsRearrangementHTMLFilter::class => [],

            \Kibo\Phast\Filters\HTML\CSSInliningHTMLFilter::class => [],

            \Kibo\Phast\Filters\HTML\ImagesOptimizationServiceHTMLFilter::class => [
                'serviceUrl' => '/Phast/images.php'
            ]

        ]
    ],

    'images' => [
        'enable-cache' => true,

        'filters' => [
            \Kibo\Phast\Filters\Image\ResizerImageFilter::class => [
                'defaultMaxWidth' => 320,
                'defaultMaxHeight' => 180
            ],

            \Kibo\Phast\Filters\Image\CompressionImageFilter::class => [
                \Kibo\Phast\Filters\Image\Image::TYPE_PNG => 9,
                \Kibo\Phast\Filters\Image\Image::TYPE_JPEG => 80
            ]
        ]
    ]
];