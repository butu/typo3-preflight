CREATE TABLE `tx_testext_domain_model_dupelement` (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    basic_bodytext text,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    UNIQUE title_unique (title)
);
