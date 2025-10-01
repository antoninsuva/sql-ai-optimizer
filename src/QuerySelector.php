<?php

namespace Soukicz\SqlAiOptimizer;

use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Client\OpenAI\Model\GPT41;
use Soukicz\Llm\Client\OpenAI\Model\GPT4oMini;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\MarkdownFormatter;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\SqlAiOptimizer\AIModel\GPT5;
use Soukicz\SqlAiOptimizer\Result\CandidateQuery;
use Soukicz\SqlAiOptimizer\Result\CandidateQueryGroup;
use Soukicz\SqlAiOptimizer\Result\CandidateResult;
use Soukicz\SqlAiOptimizer\Tool\PerformanceSchemaQueryTool;
use Soukicz\SqlAiOptimizer\Tool\QueryTool;

readonly class QuerySelector {
    public function __construct(
        private LLMChainClient $llmChainClient,
        private OpenAIClient $llmClient,
        private PerformanceSchemaQueryTool $performanceSchemaQueryTool,
        private MarkdownFormatter $markdownFormatter
    ) {
    }

    public function getCandidateQueries(?string $specialInstrutions): CandidateResult {
        $groups = [];

        $tools = [
            $this->performanceSchemaQueryTool,
        ];

        $submitInputSchema = [
            'type' => 'object',
            'required' => ['queries', 'group_name', 'group_description'],
            'properties' => [
                'group_name' => [
                    'type' => 'string',
                    'description' => 'Group name',
                ],
                'group_description' => [
                    'type' => 'string',
                    'description' => 'Description of performance impact type of the group',
                ],
                'queries' => [
                    'type' => 'array',
                    'description' => 'Array of query queryids to optimize (min 1, max 20)',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'required' => ['queryid', 'query_sample', 'schema', 'reason'],
                        'properties' => [
                            'queryid' => [
                                'type' => 'string',
                                'description' => 'The queryid from pg_stat_statements',
                            ],
                            'query_sample' => [
                                'type' => 'string',
                                'description' => 'The query text from pg_stat_statements',
                            ],
                            'schema' => [
                                'type' => 'string',
                                'description' => 'The database schema the query operates on',
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Explanation of why this query is worth optimizing - formulate it in a way that it will be obvious if mentioned numbers are about a single query or total for all queries in the group',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $tools[] = new CallbackToolDefinition(
            name: 'submit_selection',
            description: 'Submit your selection of 20 most expensive queries',
            inputSchema: $submitInputSchema,
            handler: function (array $input) use (&$groups): LLMMessageContents {
                $groups[] = $input;

                return LLMMessageContents::fromString('Selection submitted');
            }
        );

        $prompt = <<<EOT
        I need help to optimize my SQL queries on PostgreSQL 13 server. I will provide tool to query pg_stat_statements and get specific queries to optimize.
        
        Query optimization can be achieved using different perspectives like execution time, memory usage, IOPS usage, etc. You must multiple optimization types and request query candidates with different queries to performance schema.
        
        Table pg_stat_statements looks:
        
        CREATE TABLE public.pg_stat_statements (
        datname text,
        rolname text,
        userid oid,
        dbid oid,
        queryid bigint,
        query text,
        plans bigint,
        total_plan_time double precision,
        min_plan_time double precision,
        max_plan_time double precision,
        mean_plan_time double precision,
        stddev_plan_time double precision,
        calls bigint,
        total_exec_time double precision,
        min_exec_time double precision,
        max_exec_time double precision,
        mean_exec_time double precision,
        stddev_exec_time double precision,
        rows bigint,
        shared_blks_hit bigint,
        shared_blks_read bigint,
        shared_blks_dirtied bigint,
        shared_blks_written bigint,
        local_blks_hit bigint,
        local_blks_read bigint,
        local_blks_dirtied bigint,
        local_blks_written bigint,
        temp_blks_read bigint,
        temp_blks_written bigint,
        blk_read_time double precision,
        blk_write_time double precision,
        wal_records bigint,
        wal_fpi bigint,
        wal_bytes numeric
        );
        
        For analyzing use just attributes that exists in this table.
        
        After examining each group, you MUST submit your selection of queries for this group using tool "submit_selection". I am expecting to get at least four groups with 20 queries each. DO NOT end your response asking if you should proceed. Actually submit the selections immediately.

        EOT;

        if (!empty($specialInstrutions)) {
            $prompt .= "\n\n**Special instructions:**\n\n" . $specialInstrutions;
        }

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString($prompt),
        ]);

        $response = $this->llmChainClient->run(
            client: $this->llmClient,
            request: new LLMRequest(
                model: new GPT5(),
                conversation: $conversation,
                temperature: 1.0,
                maxTokens: 120_000,
                tools: $tools
            ),
        );

        $resultGroups = [];
        foreach ($groups as $group) {
            $resultGroups[] = new CandidateQueryGroup(
                name: $group['group_name'],
                description: $group['group_description'],
                queries: array_map(fn (array $query) => new CandidateQuery(
                    schema: $query['schema'],
                    queryid: $query['queryid'],
                    normalizedQuery: $query['query_sample'],
                    impactDescription: $query['reason'],
                ), $group['queries']),
            );
        }

        return new CandidateResult(
            description: $response->getLastText(),
            groups: $resultGroups,
            conversation: $conversation,
            formattedConversation: $this->markdownFormatter->responseToMarkdown($response)
        );
    }
}
