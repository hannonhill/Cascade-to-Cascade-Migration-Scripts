Summary:
========

PHP-based SOAP Web Services scripts using Cascade Server's WSDL to do
intelligent copying of assets.

This script copies assets between sites in Cascade, between
sites and the Global area, and from one instance of Cascade to another. 
You can recursively copy folders or containers or copy entire sites.

Version Compatibility and Working with other Cascade versions:
==============================================================

This script (v1.6) works with Cascade 6.7, 6.8 and 6.10.

Previous versions include:

- [v1.4](https://github.com/hannonhill/Cascade-to-Cascade-Migration-Scripts/zipball/v1.4) is compatible with Cascade 6.7.x
- [v1.2](https://github.com/hannonhill/Cascade-to-Cascade-Migration-Scripts/zipball/v1.2) is compatible with Cascade 6.4.x

There are a few places in the code where we list all asset types or 
container types. When Hannon Hill updates Cascade web services, we
need to update these lists. 

For example, in Cascade 6.7 structuredDataDefinition was renamed to
dataDefinition and xhtmlBlock was renamed to xhtmlDataDefinitionBlock.

If you'd like to get this script working with a new version of Cascade,
the first place to look is Hannon Hill's web services
change log:

http://www.hannonhill.com/kb/Web-Services/Web%20Services%20Changelog/

Installation:
=============

Edit index.php and change the list of environments (circa line 20)
to match your needs.  Then copy this folder to your web server.

Usage:
======

Connect with a web browser and fill in the form.

E.g. https://www.example.edu/copy-site/

Notes:
======

The target Site must already exist before running this script.

When copying Groups from one instance of Cascade to another, we only
preserve group members who have accounts on the target system, so if
you want an exact copy of a Group, you need to create all the member
accounts first.

If an asset refers to something outside the Folder being copied, we may
change that reference. For example, we can't copy an Index Block if the
Folder that it indexes doesn't exist in the target system. What we do
in this case is that we create the Index Block, but leave the "Indexed
Folder" property blank.

After everything is copied, we go back and copy all the access rights.
Originally, access rights were set during the copy, but this
caused too many dependency issues.

There are many dependencies between assets in Cascade. We follow
dependencies when copying, and may need to copy more than you'd think.
For example, when copying a Folder, we may need to copy the Folder's
Metadata Set, or Groups that have access to that folder. When copying a
Group, we may need to copy its base asset factory, and so on.

Occasionally, there are interdependencies that make it impossible to copy 
some assets. For example, an "events" Index Block that indexes the "event" 
Content Type, which uses the "event" Configuration Set, which includes a 
region that contains the "event" Content Type Index Block.  Before you can 
copy the Block, you have to copy the Content Type and Configuration Set, 
but before you can copy the Configuration Set, you need to copy the Block. 

Limitations and Known Issues:
=============================

Copying does not preserve folder order.

Copying does not update urls and system-asset tags that are hard-coded
in files.

You may not be able to copy very large files due to php memory limits.

We never change existing assets. If any of the assets you are 
copying already exist in the destination site, we skip over them.

When copying Users and FTP Transports, we can't copy their passwords,
because you can't read passwords using Cascade web services.

When copying Configuration Sets from the Global Area to a Site, you 
may need to edit each Configuration to set the output file extension. 
This is a required field for configurations in Sites, but not in the 
Global area (where it is part of the Target).  The script tries to do 
this for you, and will issue a warning when it can't. 

We can't copy Connectors yet.

Due to several web services issues, referencing assets by site and
path is problematic (e.g. CSI-18, CSI-72, CSI-108, CSI-113, CSI-145).
Because of this, I've done a major refactor of the script to internally
reference assets by ID instead of by site and path.

When you use web services to read an asset factory with assigned plugins, 
the plugins aren't shown in the returned asset. So when you copy such 
an asset factory, the assigned plugins are lost (CSCD-4464, CSI-150). 

Prior to Cascade 6.10.2, if a Page referred to a Template, Block, Format, 
etc. from another Site, we couldn't copy it, because reading the Page 
with Web Services displayed the links to those Assets as local rather 
than cross-site (CSI-145). 

Prior to Cascade 6.10.2 we were unable to copy asset factories of type
Format (CSI-222).

Prior to Cascade 6.4, when reading xml with web services the entire xml 
was returned on one line, so copied xml assets lost their formatting 
(CSCD-4129). 

Prior to Cascade 6.4.2, we were unable to copy assets with null dynamic 
metadata values (CSCD-6242) (empty strings were ok, but null values 
were not). This happens when you add fields to a metadata set after 
creating a page. If you see a Read Error error during a copy, try editing 
the page in Cascade. You don't have to change anything, just edit and 
save. Editing will change the null values to empty strings, allowing the 
copy to proceed. Of course, you can also run into Read Errors if your web 
services user does not have permission to access the asset in question. 

Prior to 6.4, Cascade ignored sitename/siteid when reading Blocks,
Formats, References, and Templates. So when copying these from one Site
to another within the same Cascade instance, it thinks they already
exist in the target site and won't copy them.

Prior to 6.4, when reading xml with web services, the entire xml was
returned on one line (CSCD-4129; fixed in 6.4 and later).

Comparison with Copying Assets in Cascade
=========================================

The main differences between copying assets with this script vs copying them
in Cascade are:

In Cascade, you can copy assets within the Global area, within a site 
or between sites, but you can't copy from the Global area to a site or 
vice versa, and you can't copy from one instance of Cascade to another. 

This script can't copy within the Global area or within a site, but it
can copy between sites or between instances of Cascade.

In Cascade, when you copy an asset to a new site, the new
copy will still refer back to the old site.  For example, if you
copy a page to a new site, the new page will still use the
metadata set, content type, configuration set, templates, blocks and
formats in the old site.  If, on the other hand, you use my script to
copy a page, it will update all those references to point to the new
site, and if any of those assets don't exist in the new site, it will
copy them as well.

In Cascade, when you copy assets, they inherit access rights from
their new parent folder.  When you copy assets with this script, it copies
their access rights as well.