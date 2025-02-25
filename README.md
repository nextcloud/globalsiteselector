<!--
  - SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Global Site Selector

[![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/globalsiteselector)](https://api.reuse.software/info/github.com/nextcloud/globalsiteselector)

The Global Site Selector allows you to run multiple small Nextcloud instances and redirect users to the right server.

It can run in two modes. In "master" mode the server will query the lookup-server for the users location and redirect the user to the right Nextcloud server. In "slave" mode the server will be able to receive and handle the redirects of the master.

This app will always use the same protocol (HTTP or HTTPS) to do the redirect to the slave as what is used to connect to the master node. That means if the master is accessed via HTTPS the login redirect will also happen via HTTPS.

## Configuration

To use the Global Site Connector you need to add some config parameters to the config.php

### Master

Config.php parameters to operate the server in master mode:

````
// can be chosen freely, you just have to make sure the master and
// all slaves have the same key.  Also make sure to choose a strong shared secret.
'gss.jwt.key' => 'random-key',

// operation mode
'gss.mode' => 'master',

// define a master admins, this users will not be redirected to a slave but are
// allowed to login at the master node to perform administration tasks
'gss.master.admin' => ['admin1Uid', 'admin2Uid'],

// define a class which will be used to decide on which server a user should be
// provisioned in case the lookup server doesn't know the user yet.
// Note: That this will create a user account on a global scale note for every user
//       so make sure that the Global Site Selector has verified if it is a valid user before.
//       The user disovery module might require additional config paramters you can find in
//       the documentation of the module
'gss.user.discovery.module' => '\OCA\GlobalSiteSelector\UserDiscoveryModules\UserDiscoverySAML',
// or 'gss.user.discovery.module' => '\OCA\GlobalSiteSelector\UserDiscoveryModules\UserDiscoveryOIDC'

// define a allow list for automatic login to other instance to let browsers handle the redirect properly
'gss.master.csp-allow' => ['*.myorg.com', 'node3.otherorg.com'],
````

### Slave

Config parameters to operate the server in slave mode:

````
// can be chosen freely, you just have to make sure the master and
// all slaves have the same key. Also make sure to choose a strong shared secret.
'gss.jwt.key' => 'random-key',

// operation mode
'gss.mode' => 'slave',

// url of the master, so we can redirect the user back in case of an error
'gss.master.url' => 'http://localhost/nextcloud2',

// interval (in seconds) between lookup-server updates
'gss.updatels.interval' => 86400,
````

The Slave will always redirect not logged in user to the master to perform the login.
If you want to login directly at a slave, e.g. to perform some administration tasks
you can call the login page with the parameter `?direct=1`, e.g. `https://node1.myorg.com?direct=1`

### User Discovery Modules

When users login for the first time and is not yet known by the lookup server,
different methods are possible to decide on which server the user should be located.

The GlobalSiteSelector allows you to use one of the existing methods or implementing
your own, based on the `IUserDiscoveryModule` interface.

To define which of the modules should be used you can set the `gss.user.discovery.module`
parameter as described above.

Following modules exists at the moment, some of the are highly customized for a
specific use case:

#### UserDiscoverySAML

This modules reads the location directly from a parameter of the IDP which contains
the exact URL to the slave target server. The name of the parameter can be defined this way:

````
'gss.discovery.saml.slave.mapping' => 'idp-parameter'
````

#### UserDiscoveryOIDC

This module is similar to UserDiscoverySAML.
It reads the location from an OIDC token attribute which contains
the exact URL to the slave target server. The attribute can be defined this way:

````
'gss.discovery.oidc.slave.mapping' => 'token-attribute'
````

#### ManualUserMapping

This allows you to maintain a custom json file which maps a specific key word
to a nextcloud server. The json file looks like this:

````
{
  "keyword1" : "https://server1.nextcloud.com",
  "keyword2" : "https://server2.nextcloud.com"
}

````

The additional parameters you need to specify in the config.php are the following:

````
'gss.discovery.manual.mapping.file' => '/path/to/file'
'gss.discovery.manual.mapping.parameter' => 'idp-parameter'
````

Optionally the keys in the JSON file can contain regular expressions which will
be matched against the parameter of the IDP, in this case the following config.php
parameter has to be set:

````
'gss.discovery.manual.mapping.regex' => true

````


#### RemoteUserMapping

Get user location from an external remote discovery service.
The request is POST, contains data from the sso, and should return a JSON of an array including the entry 'location' with the hostname of the destination (nextcloud instance) as value:

```
$ curl -X POST https://discovery.example.net/mapping.php
{"location":"https://node12.example.net"}
```

The request is POST, contains data from the SSO, and must return the hostname of the destination (nextcloud instance)

```
 'gss.user.discovery.module' => '\\OCA\\GlobalSiteSelector\\UserDiscoveryModules\\RemoteUserMapping',
 'gss.discovery.remote.endpoint' => 'https://discovery.example.net/mapping.php',
 'gss.discovery.remote.secret' => 'myVeryOwnLittleSecret',
```

If added, the Secret Key will be sent within the POST data as `'gsSecretKey'`
