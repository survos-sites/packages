
Markdown for BundleWorkflow

![BundleWorkflow](assets/BundleWorkflow.svg)



---
## Transition: load

### load.Transition

onLoadComposer()
        // details from packagist API
        // Slow but detailed API call

```php
#[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_LOAD)]
public function onLoadComposer(TransitionEvent $event): void
{
    $package = $this->getPackage($event);
    // @todo: check updatedAt
    // https://packagist.org/apidoc#track-package-updates
    if (true || !$data = $package->data) {
        $this->loadLatestVersionData($package);
    }
    $this->packageService->populateFromComposerData($package);
}
```
[View source](packages/blob/main/src/Workflow/BundleWorkflow.php#L140-L149)


