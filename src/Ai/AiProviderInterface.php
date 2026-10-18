<?php
namespace ATS\Ai;

interface AiProviderInterface
{
    public function complete(string $prompt): string;
}
