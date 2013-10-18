phpssrs-utils
=============

A set of utilities for SSRS built using the phpssrs library

*HEAVY IN DEVELOPMENT*

==Supports

Reporting Services 2008 onwards only.  (ReportService2010 endpoint)

==Usage

    -l Layout file (See etc/sample-layout.xml)
    -h Reporting Services endpoint URL
    -u SSRS username
    -p SSRS password
    -r Replacement root (Layout will be created under this folder)
    -d var1=val1    (variable substitutions in the layout)

==Example

    rssync \
        -l mylayout.xml
        -h http://<hostname>/reportserver
        -u DOMAIN\bob.jones
        -p mypass101
        -r /Environments/Staging
        -d "connstr1=Data Source=(local);Initial Catalog=MyDb"
        -d connuser1=user
        -d connpass1=pass
