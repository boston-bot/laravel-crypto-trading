<?php

namespace App\Enums;

enum EvaluationStage: string
{
    case Development = 'development';
    case OuterOos = 'outer_oos';
    case Holdout = 'holdout';
    case Stress = 'stress';
}
