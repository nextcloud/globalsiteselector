# Changelog

All notable changes to this project will be documented in this file.

## 2.3.3

- passing idp to slave, back to master on logout.
- storing idp in user preferences.

## 2.3.2

- new app type extended_authentication

## 2.3.1

- get full account data on login

## 2.3.0

- new session is now generated on slave when using user_saml
- fixed logout with SAML
- fix a conflict with ldap authentication
- retrieve and cache display names
- more debug log
- cleaning code

## 2.2.0

- compat nc25
- update user on login
- fix an issue on logout with saml

## 2.1.1

- config flag to ignore user's account properties

## 2.1.0

### Fixed

- #37 get account data as array @blizzz
- #56 Allow form action to handle nc:// protocol @juliushaertl
- #51 Fix session token creation "remember" parameter @eneiluj

### Other

- #40 configure Client to allow_local_remote_server based on 'gss.allow_local_address' @ArtificialOwl
- #49 Implement csp allow list for master node @juliushaertl
- #46 Github CI @juliushaertl
- #36 Deprecations and cleanup @blizzz
- #42 Update version on master @nickvergessen
- #52 Use addServiceListener for registration with the IEventDispatcher @juliushaertl

## 1.3.0

### Added

- NC19 compatible

## 1.2.1

### Added

- Extra debug info
- Added capabilities so the client knows to use the old flow
- NC18 compatible

### Changed

- Use longer generated apptokens

## 1.2.0

### Added

- NC16 and NC17 compatibility
- Added Regex matching for GSS nodes
- Added debug log statements

### Fixed

- Redirect users to GSS if they are not logged in
- Fixed non email username mappiong
- Fixed login flow with branded android clients

## 1.1.0

### Fixed

- Fix client login with the new login flow
- Fix client login when users add the username/password manually

### Changed

- Remember exact link and redirect the user to the correct sub page on the client node
- Provide the number of users known by the global scale user back-end

## 1.0.0

### Changed

- First stable version of the Global Site Selector
- Works with local user back-ends and SAML

