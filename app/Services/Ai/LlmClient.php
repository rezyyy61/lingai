<?php

namespace App\Services\Ai;

interface LlmClient
{
    public function chat(array $messages, array $options = []): LlmResult;

    public function chatJson(array $messages, array $options = []): LlmResult;
}
