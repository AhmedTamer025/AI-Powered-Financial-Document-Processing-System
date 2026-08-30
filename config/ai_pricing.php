<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Pricing Configuration
    |--------------------------------------------------------------------------
    |
    | All token prices are USD per 1,000,000 tokens.
    | Page prices are USD per page.
    |
    | The application should use this configuration for calculating AI costs.
    |
    */

    'models' => [

        /*
        |--------------------------------------------------------------------------
        | Mistral Large
        |--------------------------------------------------------------------------
        */

        'mistral-large-latest' => [

            'provider' => 'mistral',

            'pricing' => [

                'type' => 'token',

                'input' => (float) env(
                    'BENCHMARK_MISTRAL_INPUT_PRICE',
                    0.50
                ),

                'output' => (float) env(
                    'BENCHMARK_MISTRAL_OUTPUT_PRICE',
                    1.50
                ),

                'reasoning' => (float) env(
                    'BENCHMARK_MISTRAL_REASONING_PRICE',
                    1.50
                ),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Gemini 2.5 Flash
        |--------------------------------------------------------------------------
        */

        'gemini-2.5-flash' => [

            'provider' => 'gemini',

            'pricing' => [

                'type' => 'token',

                'input' => (float) env(
                    'BENCHMARK_GEMINI_INPUT_PRICE',
                    0.30
                ),

                'output' => (float) env(
                    'BENCHMARK_GEMINI_OUTPUT_PRICE',
                    2.50
                ),

                'reasoning' => (float) env(
                    'BENCHMARK_GEMINI_REASONING_PRICE',
                    2.50
                ),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Mistral OCR
        |--------------------------------------------------------------------------
        |
        | Mistral OCR is priced per page, not per token.
        |
        */

        'mistral-ocr-latest' => [

            'provider' => 'mistral',

            'pricing' => [

                'type' => 'page',

                'page' => (float) env(
                    'BENCHMARK_MISTRAL_OCR_PAGE_PRICE',
                    0.001
                ),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Pricing
    |--------------------------------------------------------------------------
    |
    | Used when a model does not have an explicit pricing configuration.
    |
    */

    'default' => [

        'type' => 'token',

        'input' => 0.0,

        'output' => 0.0,

        'reasoning' => 0.0,

        'page' => 0.0,
    ],

];
