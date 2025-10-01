<?php

namespace Soukicz\SqlAiOptimizer\Tool;

use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\Llm\Tool\ToolResponse;
use Soukicz\SqlAiOptimizer\Service\DatabaseQueryExecutor;

class PerformanceSchemaQueryTool implements ToolDefinition {
    public function __construct(
        private DatabaseQueryExecutor $queryExecutor,
        private bool $cacheDatabaseResults
    ) {
    }

    public function getName(): string {
        return 'pg_stat_statements_query';
    }

    public function getDescription(): string {
        return 'Run SQL query against pg_stat_statements and return results as markdown table. Only first 250 rows are returned.';
    }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['query'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'SQL query to run against pg_stat_statements',
                ],
            ],
        ];
    }

    public function handle(array $input): LLMMessageContents {
        return LLMMessageContents::fromString($this->queryExecutor->executeQuery('public', $input['query'], $this->cacheDatabaseResults, 250));
    }
}
