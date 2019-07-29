# backup

 Backup predefined target(s)
 
 	$scriptName backup --target=photos
 	$scriptName backup --target=cloud-core
 	$scriptName backup --target=photos,cloud-core,mystuff
  
 Backup location to default target
 
	$scriptName backup /volume1/foo
  
 Backup location to different directory/bucket
 
	$scriptName backup /volume1/foo /somewhere
	$scriptName backup /volume1/foo b2-core:
	$scriptName backup /volume1/foo b2-core:/other/dir

# restore 

Restore predefined target(s)

 	$scriptName restore --target=photos
 	$scriptName restore --target=cloud-core
 	$scriptName restore --target=photos,cloud-core,mystuff
  
 Restore predefined target to different directory
 
 	$scriptName restore --target=adorable-illusion /tmp/restore
  
 Restore location from default target
 
	$scriptName restore /volume1/foo
	$scriptName restore /volume1/NetBackup/foo	[same as previous]
	$scriptName restore b2-core:
	$scriptName restore b2-core:/volume1/somedir
  
 Restore location from default target elsewhere
 
	$scriptName restore /volume1/foo /elsewhere
	$scriptName restore /volume1/NetBackup/foo /elsewhere	[same as previous]
	$scriptName restore b2-core: /elsewhere
	$scriptName restore b2-core:/somedir /elsewhere
