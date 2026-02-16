<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

enum EmbeddingDriver: string
{
    case OPENAI = 'openai';
    case MULTIEMBEDDER = 'multiembedder';
}
