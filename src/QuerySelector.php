<?php

namespace Soukicz\SqlAiOptimizer;

use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Client\OpenAI\Model\GPT41;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\MarkdownFormatter;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\SqlAiOptimizer\Result\CandidateQuery;
use Soukicz\SqlAiOptimizer\Result\CandidateQueryGroup;
use Soukicz\SqlAiOptimizer\Result\CandidateResult;
use Soukicz\SqlAiOptimizer\Tool\PerformanceSchemaQueryTool;
use Soukicz\SqlAiOptimizer\Tool\QueryTool;

readonly class QuerySelector {
    public function __construct(
        private LLMChainClient $llmChainClient,
        private OpenAIClient $llmClient,
        private QueryTool $performanceSchemaQueryTool,
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
                    'description' => 'Array of query digests to optimize (min 1, max 20)',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'required' => ['query_sample', 'reason'],
                        'properties' => [
                            'digest' => [
                                'type' => 'string',
                                'description' => 'The query digest hash from pg_stat_statements_import',
                            ],
                            'query_sample' => [
                                'type' => 'string',
                                'description' => 'The query text from pg_stat_statements_import',
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
            handler: function (array $input) use (&$groups): string {
                $groups[] = $input;

                return 'Selection submitted';
            }
        );

        $prompt = <<<EOT
        I need help to optimize my SQL queries on PostgreSQL 13 server. I will provide tool to query pg_stat_statements and get specific queries to optimize.
        
        Query optimization can be achieved using standard table from PostgreSQL named pg_stat_statements.

        After examinig each group, submit your selection of queries for this group using tool "submit_selection". I am expecting to get at least four groups with 20 queries each.

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
                model: new GPT41(GPT41::VERSION_2025_04_14),
                conversation: $conversation,
                temperature: 1.0,
                maxTokens: 32_767,
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
                    digest: $query['digest'],
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
