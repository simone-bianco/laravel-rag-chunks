<?php

namespace SimoneBianco\LaravelRagChunks\Exceptions;

/**
 * Thrown when a stop signal is detected during post-processing.
 * Does NOT extend PostProcessingException to avoid being wrapped by the PostProcessor catch block.
 */
class ProcessStoppedException extends \RuntimeException {}
