<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

enum EmbeddingDriver: string
{
    case OPENAI = 'openai';
    case E5_LARGE = 'e5-large';
    case BGE_M3 = 'bge-m3';
    case NOMIC = 'nomic';
}
