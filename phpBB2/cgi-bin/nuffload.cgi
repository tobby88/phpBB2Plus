#!/usr/bin/perl -W

# Author : Nuffmon 2005
# http://www.nuffmon.oftheweek.de
# Version 1.4.2
# Last Update 19/11/2005
#
# The Initial Developer of the Original Code is Raditha Dissanayake.
# Portions created by Raditha are Copyright (C) 2003
# Raditha Dissanayake. All Rights Reserved.


use strict;
use warnings;
use CGI;

my $qstring = "";
my %query = ();

if (length ($ENV{'QUERY_STRING'}) > 0){
	my $buffer = $ENV{'QUERY_STRING'};
	my @pairs = split(/&/, $buffer);
	foreach my $pair (@pairs){
	   my ($name, $value) = split(/=/, $pair, 2);
	   next unless defined $name && defined $value;
	   $name =~ tr/+/ /;
	   $value =~ tr/+/ /;
	   $name =~ s/%([a-fA-F0-9][a-fA-F0-9])/pack("C", hex($1))/eg;
           $value =~ s/%([a-fA-F0-9][a-fA-F0-9])/pack("C", hex($1))/eg;
	   $query{$name} = $value;
	   if ($name =~ /^(?:cat_id|user_id|sid)$/) {
	       my $safe_name = &url_encode($name);
	       my $safe_value = &url_encode($value);
	       $qstring .= "$safe_name=$safe_value&";
	   }
      }
 }

my $psid = defined $query{'psid'} ? $query{'psid'} : '';
if ($psid !~ /^[a-fA-F0-9]{32}$/) {
  print "Status: 400 Bad Request\nContent-type: text/plain\n\nInvalid upload session\n";
  exit;
}

my $post_data_file = "tmp/" . $psid . "_postdata";
my $monitor_file = "tmp/" . $psid . "_flength";
my $qstring_file = "tmp/" . $psid . "_qstring";
my $received_file = "tmp/" . $psid . "_received";
my $complete_file = "tmp/" . $psid . "_complete";
my $owner_file = "tmp/" . $psid . "_owner";

if (!-f $owner_file) {
  print "Status: 403 Forbidden\nContent-type: text/plain\n\nUnknown upload session\n";
  exit;
}

my $len = defined $ENV{'CONTENT_LENGTH'} && $ENV{'CONTENT_LENGTH'} =~ /^\d+$/ ? int($ENV{'CONTENT_LENGTH'}) : 0;
my $bRead=0;
$|=1;

unlink("$received_file") if -e "$received_file";
unlink("$complete_file") if -e "$complete_file";

# Check for max upload size, set to whatever you want
if($len <= 0 || $len > 32000000)
{
  close (STDIN);
  print "Content-type: text/html\n\n";
  print "<br>The maximum upload size has been exceeded<br>\n";
  exit;
}

# Send content-length to monitor file
if (-e "$monitor_file") {
  unlink("$monitor_file");
}
open (my $monitor_handle, '>', $monitor_file) or &bye_bye();
print {$monitor_handle} $len;
close ($monitor_handle);
sleep(1);

# read and store the raw post data on a temporary file so that we can
# pass it though to a CGI instance later on.
if (-e "$post_data_file") {
  unlink("$post_data_file");
}
open(my $post_handle, '>', $post_data_file) or &bye_bye();
my $i=0;
my $ofh = select($post_handle); $| = 1; select ($ofh);
my $line = '';
while (read (STDIN, $line, 4096) && $bRead < $len )
{
  $bRead += length $line;
  $i++;
  print {$post_handle} $line;
}
close ($post_handle);

#
# We don't want to decode the post data ourselves. That's like
# reinventing the wheel. If we handle the post data with the perl
# CGI module that means the PHP script does not get access to the
# files, but there is a way around this.
#
# We can ask the CGI module to save the files, then we can pass
# these filenames to the PHP script. In other words instead of
# giving the raw post data (which contains the 'bodies' of the
# files), we just send a list of file names.
#

open(STDIN, '<', $post_data_file) or &bye_bye();
my $cg = CGI->new();
my %vars = $cg->Vars;
my $j = 0;

while(my ($key, $value) = each %vars)
{
  my $file_upload = $cg->param($key);
  $key =~ s/[^a-zA-Z0-9_-]//g;
  next if $key eq '';
  if(defined $value && $value ne '')
  {
    my $fh = $cg->upload($key);
    if(defined $fh)
    {
	  my $tmp_filename = "tmp/$psid"."_actualdata"."$j";
	  open(my $upload_handle, '>', $tmp_filename) or &bye_bye();
	  binmode($upload_handle);
      while(<$fh>) {
		print {$upload_handle} $_;
      }
	  close($upload_handle);
	  my $fsize =(-s $tmp_filename);
	  my $safe_upload_name = &url_encode($file_upload);
	  my $safe_tmp_filename = &url_encode($tmp_filename);
	  my $safe_key = &url_encode($key);
	  $qstring .= "file[name][$j]=$safe_upload_name&file[size][$j]=$fsize&";
	  $qstring .= "file[tmp_name][$j]=$safe_tmp_filename&";
	  $qstring .= "file[field][$j]=$safe_key&";
      $j++;
    }
    else
    {
	  my $safe_key = &url_encode($key);
	  my $safe_value = &url_encode($value);
	  $qstring .= "$safe_key=$safe_value&" ;
    }
  }
}

# Write query string to file.
if (-e "$qstring_file") {
  unlink("$qstring_file");
}
open (my $qstring_handle, '>', $qstring_file) or &bye_bye();
print {$qstring_handle} $qstring;
close ($qstring_handle);

# Tidy up after ourselves.
unlink("$monitor_file");
unlink("$post_data_file");

# Keep a small hand-off marker so the polling popup can distinguish server-side
# image processing from a transfer that has not started yet.
open (my $received_handle, '>', $received_file) or &bye_bye();
print {$received_handle} $len;
close ($received_handle);

# OK lets get back to album upload.
my $url = "../album_upload.php?psid=$psid";
if (defined $query{'sid'} && $query{'sid'} =~ /^[a-fA-F0-9]{32}$/) {
  $url .= "&sid=" . $query{'sid'};
}
print "Location: $url\n\n";

sub url_encode
{
  my ($value) = @_;
  $value = '' unless defined $value;
  $value =~ s/([^a-zA-Z0-9_\-.])/uc sprintf("%%%02x", ord($1))/eg;
  return $value;
}

sub bye_bye
{
  print "Status: 500 Internal Server Error\nContent-type: text/plain\n\nUpload processing failed\n";
  exit;
}
