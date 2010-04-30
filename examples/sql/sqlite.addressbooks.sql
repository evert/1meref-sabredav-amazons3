CREATE TABLE addressbooks (
    id integer primary key asc, 
    principaluri text, 
    displayname text, 
    uri text,
    description text
);

CREATE TABLE cards ( 
	id integer primary key asc, 
    addressbookid integer, 
    carddata text, 
    uri text, 
    lastmodified integer
);

