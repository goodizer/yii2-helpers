yii2-helpers
=================

[![Latest Stable Version](https://img.shields.io/packagist/v/goodizer/yii2-helpers.svg)](https://packagist.org/packages/goodizer/yii2-helpers)
[![License](https://poser.pugx.org/goodizer/yii2-helpers/license)](https://packagist.org/packages/goodizer/yii2-helpers)
[![Total Downloads](https://poser.pugx.org/goodizer/yii2-helpers/downloads)](https://packagist.org/packages/goodizer/yii2-helpers)
[![Monthly Downloads](https://poser.pugx.org/goodizer/yii2-helpers/d/monthly)](https://packagist.org/packages/goodizer/yii2-helpers)
[![Daily Downloads](https://poser.pugx.org/goodizer/yii2-helpers/d/daily)](https://packagist.org/packages/goodizer/yii2-helpers)

## Installation

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

> Note: Check the [composer.json](https://github.com/goodizer/yii2-helpers/blob/master/composer.json) for this extension's requirements and dependencies. 

Either run

```
$ php composer.phar require goodizer/yii2-helpers
```

or add

```
"goodizer/yii2-helpers": "*"
```

to the ```require``` section of your `composer.json` file.

## Usage

### GridSearchHelper

Create ActiveDataProvider object and build query by GET|POST data validated in model, which will be in filterModel property for GridView, ListView, etc.

```php
use goodizer\helpers\GridSearchHelper;
use yii\grid\GridView;

$searchData = GridSearchHelper::search(new Note());
echo GridView::widget([
	'columns' => [
		'id',
		'name',
		'etc',
	],
    'filterModel' => $searchData->filterModel,
    'dataProvider' => $searchData->dataProvider,
]);
```

### DbSyncHelper

Create or modify tables by model attribute types. Also can add CONSTRAINT REFERENCES.

```php
use goodizer\helpers\DbSyncHelper;

$sync = new DbSyncHelper([
  'common\models',
  'modules\admin\models',
  'some\another\namespace',
]);
$sync->run();
```
