CREATE SEQUENCE IF NOT EXISTS ocwebhook_failure_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;

CREATE TABLE IF NOT EXISTS ocwebhook_failure (
   id integer DEFAULT nextval('ocwebhook_failure_s'::text) NOT NULL,
   job_id integer DEFAULT 0 NOT NULL,
   executed_at integer,
   response_headers TEXT,
   response_status integer DEFAULT NULL,
   hostname TEXT,
   pid TEXT,
   scheduled integer not null default 0
);
ALTER TABLE ONLY ocwebhook_failure ADD CONSTRAINT ocwebhook_failure_pkey PRIMARY KEY (id);
CREATE INDEX ocwebhook_failure_job ON ocwebhook_failure USING btree (job_id);

ALTER TABLE ocwebhook ADD COLUMN retry_enabled integer not null default 1;