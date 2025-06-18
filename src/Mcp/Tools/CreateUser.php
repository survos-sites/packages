<?php

namespace App\Mcp\Tools;

use App\Mcp\Schema\CreateUserSchema;
use Ecourty\McpServerBundle\Attribute\AsTool;
use Ecourty\McpServerBundle\Attribute\ToolAnnotations;
use Ecourty\McpServerBundle\IO\TextToolResult;
use Ecourty\McpServerBundle\IO\ToolResult;

#[AsTool(
    name: 'create_user', # Unique identifier for the tool, used by clients to call it
    description: 'Creates a new user in the system', # This description is used by LLMs to understand the tool's purpose
    annotations: new ToolAnnotations(
        title: 'Create a user', // A human-readable title for the tool, useful for documentation
        readOnlyHint: false, // Defines the request is not read-only (creates a user)
        destructiveHint: false, // Defines the request is not destructive (does not delete data)
        idempotentHint: false, // Defines the request cannot be repeated without changing the state
        openWorldHint: false, // The tool does not interact with external systems
    )
)]
class CreateUser
{
    public function __invoke(CreateUserSchema $createUserSchema): ToolResult
    {
        // Your logic here...
        return new ToolResult([new TextToolResult('User created successfully!')]);
    }
}
