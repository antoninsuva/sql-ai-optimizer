<?php

namespace Soukicz\SqlAiOptimizer\AIModel;

use Soukicz\Llm\Client\OpenAI\Model\OpenAIModel;

class GPT5 extends OpenAIModel
{
    public const VERSION_2025_08_07 = '2025-08-07';

    public function __construct(
        private string $version = self::VERSION_2025_08_07,
    )
    {
    }

    public function getCode(): string
    {
        return 'gpt-5-nano-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float
    {
        return 1.25;
    }

    public function getOutputPricePerMillionTokens(): float
    {
        return 10.0;
    }

    public function getCachedInputPricePerMillionTokens(): float
    {
        return 0.13;
    }

    public function getCachedOutputPricePerMillionTokens(): float
    {
        return 0.0;
    }
}