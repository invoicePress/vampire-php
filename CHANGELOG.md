# Changelog

## 1.2.0

The first public release.

- VAMPIRE PHP is free software under the GNU General Public License, version 2 or later, and installs as
  `invoicepress/vampire-php` from Packagist.
- The rule packages state origin, rights holder and licence of their source in their head; `NOTICE` lists them, and
  `licences/` holds the licence texts. Rule sets whose licence is not cleared are not part of the package; a document
  that needs one gets no verdict and names the missing rule set.
- A rule package records the path of its source relative to the package, no longer as an absolute path.
- Checks XRechnung 3.0.2, the EN 16931 core rules, Peppol BIS Billing 3.0.21 and Factur-X / ZUGFeRD 2.5.2 in the
  profiles EN16931 and EXTENDED, and every edition of Factur-X / ZUGFeRD from 2.1 to 2.5.2 for the check against every
  edition.
- Optional positive test report: every rule checked, with its result.
- Rule profiles: own rules on top of the official ones, with a format shared with the JavaScript implementation.
