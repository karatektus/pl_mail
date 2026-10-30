**`doctrine:schema:validate` passes.** The entity mapping now describes the database as it is, and a
test keeps the two in step. Migration `Version20261030130000` turns 17 hand-written `SERIAL` ids
into identity columns. [#443](https://github.com/karatektus/pl_mail/issues/443).
