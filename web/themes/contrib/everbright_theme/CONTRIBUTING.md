```shell
mkdir everbright && cd everbright
ddev config --project-type=drupal11 --docroot=web
ddev composer create-project drupal/cms
ddev composer config minimum-stability dev
ddev composer require drupal/everbright
ddev drush si ../recipes/everbright
```
