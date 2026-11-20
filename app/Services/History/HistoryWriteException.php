<?php

namespace App\Services\History;

use RuntimeException;

/**
 * The history could not be written. Callers must stop the flow and must
 * not execute anything (an unrecorded execution is not allowed).
 */
final class HistoryWriteException extends RuntimeException {}
