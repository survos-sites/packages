# Implementing Melia InstantSearch with Symfony

I'm a big fan of MeiliSearch.  When Symfony announced that they were switching to it (link), I also made the switch.  It was great then, and keeps getting better.

In this article, I'm going to implement InstantSearch with a distinctively Symfony flavor, using StimulusJS for the javascript and Twig for rendering the output.

Meilisearch provides an readonly endpoint of a movie index, so we will skipping configuring the index and loading the data.

This simple project will demonstrate the insta-search and also provide a detail page, which will be rendered with data fetched from Meilisearch but use normal twig to render it.

Create a new project and install some dependencies we'll need.

```bash
symfony new --webapp insta-movies && cd insta-movies
composer install
composer req meilisearch...
```

Make a Symfony controller for the home page of the application, and a stimulus controller to do the dynamic search.

```bash
bin/console make:controller App
bin/console make:stimulus search
```

Since the app uses a public readonly key, we can simply add the configuration data to the .env file

```
MEILI_SEARCH_KEY=
MEILISERVER=
```

AppController needs two routes, the index and the detail page.  Both need the server and API key, so we'll autowire those in the constructor.  All the work on the index page happens in javascript, so we'll just pass it.  For the detail page we'll fetch a single item using the PHP library, and pass it to the template.

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(MEILI_SERVER)%')] private string $meiliServer,
        #[Autowire('%env(MEILI_API_KEY)%')] private string $apiKey,
    ) {}

    #[Route('/', name: 'app_homepage')]
    public function index(): Response
    {
        return $this->render('app/insta.html.twig', [
            'server' => $this->meiliServer,
            'apiKey' => $this->apiKey
        ]);
    }


}

```
