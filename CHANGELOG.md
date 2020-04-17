# Changelog
All notable changes to this project will be documented in this file.

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

