Summary
========

SOAP Web Services scripts using Cascade Server's WSDL that can intelligently migrate content from one instance of Cascade to another.

This script copies assets between sites in Cascade or from one
instance of Cascade to another.  You can recursively copy folders
or containers, or copy entire sites.

Installation
============

Edit index.php and change the list of environments (circa line 20) to match your needs. 
Then copy this folder to your web server.

Usage
=====

Connect with a web browser and fill in the form.
E.g. https://www.example.edu/copy-site/

Notes, Limitations and Known Issues
===================================

The target site must already exist before running this script.

We never change existing assets. If any of the assets you are 
copying already exist in the destination site, we skip over them.

Unfortunately, there are some things we just can't copy.

Due to a Web Services bug in the early Cascade 6.4 releases (CSCD-6242; fixed in 6.4.2 and later), 
we are unable to copy assets with null dynamic metadata values (empty strings are ok,
but null values are not). This happens when you add fields to a
metadata set after creating a page. If you see a Read Error error
during a copy, try editing the page in Cascade. You don't have to
change anything, just edit and save. Editing will change the null
values to empty strings, allowing the copy to proceed. Of course, you
can also run into Read Errors if your web services user does not have
permission to access the asset in question.

When copying Users and FTP Transports, we can't copy their passwords,
because you can't read passwords using Cascade web services.

We can't copy Site Destinations. Due to a bug in web services, you
can't create a destination inside a site if you specify it's
parentContainerPath; it only works if you specify the parentContainerId.

Copying does not preserve folder order.

Copying does not update urls and system-asset tags that are hard-coded
in files.

You may not be able to copy very large files due to php memory limits.

When you use web services to read an Asset Factory that has plugins
assigned, the plugins aren't shown in the returned asset. So when you
copy such an asset factory, the assigned plugins are lost (CSCD-4464).

Prior to 6.4, Cascade ignored sitename/siteid when reading Blocks,
Formats, References, and Templates. So when copying these from one Site
to another within the same Cascade instance, it thinks they already
exist in the target site and won't copy them.

Prior to 6.4, when reading xml with web services, the entire xml was
returned on one line (CSCD-4129; fixed in 6.4 and later).

In Cascade 6.0, we were unable to copy Asset Factories of type Format.
I haven't tested this in 6.4 yet.

There are many dependencies between assets in Cascade. We follow
dependencies when copying, and may need to copy more than you'd think.
or example, when copying a Folder, we may need to copy the Folder's
Metadata Set, or Groups that have access to that folder. When copying a
Group, we may need to copy its base asset factory, and so on.

Occasionally, there are interdependencies that make it impossible to
copy some assets. For example, an "events" Bage uses the "event" Content
Type, which uses the "event" Configuration Set, which includes a region
that uses the "event" Block. Before you can copy the Block, you have to
copy the content type and configuration set, but before you can copy
the Configuration Set, you need to copy the Block.

When copying Groups from one instance of Cascade to another, we only
preserve group members who have accounts on the target system, so if
you want an exact copy of a Group, you need to create all the member
accounts first.

If an asset refers to something outside the Folder being copied, we may
change that reference. For example, we can't copy an Index Block if the
Folder that it indexes doesn't exist in the target system. What we do
in this case is that we create the Index Block, but leave the "Indexed
Folder" property blank.

We can't copy Connectors yet.

After everything is copied, we go back and copy all the access rights.
So, if copying is interrupted by an error, access rights will not
be set.  Originally, access rights were set during the copy, but this
caused too many dependency issues.

Working with other Cascade versions
===================================

There are a few places in index.php and cascade_soap_lib.php
where we list all known asset types or container types, or both.

In particular, if the script doesn't run at all with a new version
of Cascade, the first place to look is the list of types in the
clean_asset method in cascade_soap_lib.php.
