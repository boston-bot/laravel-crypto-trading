<?php

return [
    'enabled' => filter_var(env('MCP_STRATEGY_EXPERIMENTS_ENABLED', false), FILTER_VALIDATE_BOOL),
];
