<?php

namespace App\Enums;

enum ExperimentStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Inconclusive = 'inconclusive';
}
