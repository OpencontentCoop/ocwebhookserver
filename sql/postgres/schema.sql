CREATE SEQUENCE ocwebhook_s
    START 1
    INCREMENT 1
    MAXVALUE 9223372036854775807
    MINVALUE 1
    CACHE 1;


CREATE TABLE ocwebhook (
   id integer DEFAULT nextval('ocwebhook_s'::text) NOT NULL,
   name VARCHAR(255) NOT NULL,
   url VARCHAR(255) NOT NULL,
   enabled integer not null default 0,
   payload_params TEXT,
   method VARCHAR(255) DEFAULT 'post',
   content_type VARCHAR(255) DEFAULT 'application/json',
   headers TEXT,
   secret TEXT,
   created_at integer
);
ALTER TABLE ONLY ocwebhook ADD CONSTRAINT ocwebhook_pkey PRIMARY KEY (id);
CREATE UNIQUE INDEX ocwebhook_identifier ON ocwebhook USING btree (name, url);
CREATE INDEX ocwebhook_name ON ocwebhook USING btree (name);

CREATE TABLE ocwebhook_trigger_link (
  webhook_id integer DEFAULT 0 NOT NULL,
  trigger_identifier VARCHAR(255) NOT NULL,
  filters TEXT
);

ALTER TABLE ONLY ocwebhook_trigger_link ADD CONSTRAINT ocwebhook_trigger_link_pkey PRIMARY KEY (webhook_id, trigger_identifier);
CREATE INDEX ocwebhook_trigger_link_wtid ON ocwebhook_trigger_link USING btree (webhook_id, trigger_identifier);
CREATE INDEX ocwebhook_trigger_link_tid ON ocwebhook_trigger_link USING btree (trigger_identifier);

CREATE SEQUENCE ocwebhook_job_s
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;


CREATE TABLE ocwebhook_job (
   id integer DEFAULT nextval('ocwebhook_job_s'::text) NOT NULL,
   execution_status integer DEFAULT 0 NOT NULL,
   webhook_id integer DEFAULT 0 NOT NULL,
   trigger_identifier VARCHAR(255) NOT NULL,
   payload TEXT,
   created_at integer,
   executed_at integer,
   response_headers TEXT,
   response_status integer DEFAULT NULL,
   hostname TEXT,
   pid TEXT
);
ALTER TABLE ONLY ocwebhook_job ADD CONSTRAINT ocwebhook_job_pkey PRIMARY KEY (id);
CREATE INDEX ocwebhook_job_status ON ocwebhook_job USING btree (execution_status);