DR_FastCatalogUrlIndexer
========================
This module reduces the time to index catalog url rewrites. Products that are disabled or not visible will excluded form indexing process.

Installation
-------
For installing this module, there are various ways:

* modman
  
  ```
  modman init 																# if modman is not initialized
  modman clone https://github.com/daniel-rose/DR_FastCatalogUrlIndexer.git	# gets the latest code from the master branch and links it
  ```

* composer

  Add the following line to the file "composer.json":

  `"dr/fast-catalog-url-indexer": "*"`

* copy/paste

  Download the latest code from github and copy all files to the magento root directory.

By using modman or composer the setting "allow symlinks" must be enabled. After the module is installed, the cache has to be cleared.

Developer
---------
Daniel Rose
* Xing: https://www.xing.com/profile/Daniel_Rose16

Licence
-------
[OSL - Open Software Licence 3.0](http://opensource.org/licenses/osl-3.0.php)

Copyright
---------
(c) 2015 Daniel Rose