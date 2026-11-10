```shell
mkdir forma && cd forma
ddev config --project-type=drupal11 --docroot=web
ddev composer create-project drupal/cms
ddev composer config minimum-stability dev
ddev composer require drupal/forma
ddev drush si ../recipes/forma
```
