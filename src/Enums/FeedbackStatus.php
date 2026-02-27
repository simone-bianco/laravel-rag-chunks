<?php

namespace SimoneBianco\LaravelRagChunks\Enums;

enum FeedbackStatus: string
{
    case PENDING = 'pending';
    case DELIVERED = 'delivered';
}
