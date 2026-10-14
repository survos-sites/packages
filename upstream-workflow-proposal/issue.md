### Description

With Symfony 8.2's attribute-based workflows, could the existing `workflow` service tag expose the declared support classes and the class carrying `#[AsWorkflow]`?

Workflow explorers, documentation generators and Doctrine-backed CLI tools need to discover workflows before they have a subject instance. Symfony already provides the `workflow` tag and named service locators, and `Registry::get()` / `all()` handle an existing subject. The remaining gap is discovering the declared class-to-workflow relationship and the source definition class without re-reading attributes/configuration or inspecting the registry's service definitions.

For example:

```php
#[AsWorkflow(name: 'publication', supports: [Article::class])]
final class PublicationWorkflow
{
    // ...
}
```

A compiler pass could consume:

```php
[
    'name' => 'publication',
    // Existing tag attributes remain unchanged.
    'supports' => [Article::class],
    'definition_class' => PublicationWorkflow::class,
]
```

### Proposed scope

* Add `supports` to the `workflow` tag, preserving the normalized list of explicitly declared supported classes/interfaces for both configuration-defined and attribute-defined workflows.
* Add nullable `definition_class`, populated for attribute-defined workflows and `null` for configuration-defined workflows.
* Keep registry lookup and support-strategy behavior unchanged. An empty `supports` list is not a claim that no objects are supported: custom support strategies can decide at runtime, and injection-only workflows can have no declared supports.
* Keep this separate from user-defined workflow metadata.

This would let downstream tooling consume Symfony's compiled declarations instead of maintaining its own parallel registration map. It applies equally to state machines and multi-place workflows. It does not require entities to implement a marker interface or construct an entity just to discover its declared workflows.

### Historical context

I originally requested workflow definition access for generated documentation in #19938. That proposal was initially declined and later implemented. `getDefinition()` now addresses that original need; this is a smaller remaining introspection gap, made more useful by the new 8.2 workflow attributes.

Would adding these two attributes to the existing service tag be an acceptable approach?
