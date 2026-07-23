import React, { useEffect, useMemo, useState } from "react";
import CrudModal from "../../Global/components/CrudModal";

const EMPTY_FORM = {
  nombre: "",
  usuario: "",
  email: "",
  id_rol: "",
  contrasena: "",
};

const USERNAME_PATTERN = /^[A-Za-z0-9._-]{3,100}$/;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i;

const fieldClass = (value, error) =>
  `entity-field ${value !== "" && value !== null && value !== undefined ? "is-active" : ""} ${error ? "has-error" : ""}`.trim();

export default function UsuarioSistemaModal({
  open,
  user,
  roles,
  currentUserId,
  saving,
  onClose,
  onSave,
}) {
  const editing = Boolean(user);
  const isSelf = editing && Number(user?.id_usuario) === Number(currentUserId);
  const [form, setForm] = useState(EMPTY_FORM);
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    const defaultRole = roles?.find((role) => !role.es_super_admin) || roles?.[0];
    setForm({
      nombre: user?.nombre || "",
      usuario: user?.usuario || "",
      email: user?.email || "",
      id_rol: user?.rol?.id ? String(user.rol.id) : String(defaultRole?.id || ""),
      contrasena: "",
    });
    setErrors({});
  }, [open, roles, user]);

  const roleOptions = useMemo(() => roles || [], [roles]);

  const change = (field, value) => {
    let nextValue = value;
    if (field === "usuario") {
      nextValue = value.replace(/[^A-Za-z0-9._-]/g, "").slice(0, 100);
    }
    if (field === "email") {
      nextValue = value.toLowerCase().replace(/\s+/g, "").slice(0, 180);
    }
    if (field === "nombre") {
      nextValue = value.slice(0, 160);
    }
    setForm((current) => ({ ...current, [field]: nextValue }));
    setErrors((current) => ({ ...current, [field]: undefined }));
  };

  const validate = () => {
    const nextErrors = {};
    if (form.nombre.trim().length < 3) {
      nextErrors.nombre = "Ingresá el nombre completo.";
    }
    if (!USERNAME_PATTERN.test(form.usuario)) {
      nextErrors.usuario = "Usá 3 o más caracteres válidos.";
    }
    if (form.email && !EMAIL_PATTERN.test(form.email)) {
      nextErrors.email = "Ingresá un correo válido.";
    }
    if (!form.id_rol) {
      nextErrors.id_rol = "Seleccioná un rol.";
    }
    if (!editing && form.contrasena.length < 12) {
      nextErrors.contrasena = "Usá una contraseña de al menos 12 caracteres.";
    }
    if (editing && form.contrasena && form.contrasena.length < 12) {
      nextErrors.contrasena = "Usá una contraseña de al menos 12 caracteres.";
    }
    setErrors(nextErrors);
    return Object.keys(nextErrors).length === 0;
  };

  const submit = async (event) => {
    event.preventDefault();
    if (!validate()) return;
    try {
      await onSave({
        nombre: form.nombre.trim(),
        usuario: form.usuario.trim(),
        email: form.email.trim(),
        id_rol: Number(form.id_rol),
        contrasena: form.contrasena,
      });
    } catch (error) {
      if (error?.fields && Object.keys(error.fields).length) {
        setErrors((current) => ({ ...current, ...error.fields }));
      }
    }
  };

  return (
    <CrudModal
      onClose={onClose}
      onSubmit={submit}
      open={open}
      saving={saving}
      savingLabel={editing ? "Actualizando..." : "Creando..."}
      submitLabel={editing ? "Guardar cambios" : "Crear usuario"}
      subtitle={
        editing
          ? "Actualizá los datos y permisos de acceso al sistema interno."
          : "Creá una cuenta con acceso exclusivo al sistema interno actual."
      }
      title={editing ? "Editar usuario" : "Agregar usuario"}
      wide
    >
      <div className="user-form-grid">
        <label className={fieldClass(form.nombre, errors.nombre)}>
          <input
            autoComplete="name"
            disabled={saving}
            maxLength={160}
            onChange={(event) => change("nombre", event.target.value)}
            value={form.nombre}
          />
          <span>Nombre completo *</span>
          {errors.nombre ? <small className="entity-field__error">{errors.nombre}</small> : null}
        </label>

        <label className={fieldClass(form.usuario, errors.usuario)}>
          <input
            autoComplete="username"
            disabled={saving}
            maxLength={100}
            onChange={(event) => change("usuario", event.target.value)}
            value={form.usuario}
          />
          <span>Usuario *</span>
          {errors.usuario ? <small className="entity-field__error">{errors.usuario}</small> : null}
        </label>

        <label className={fieldClass(form.email, errors.email)}>
          <input
            autoComplete="email"
            disabled={saving}
            inputMode="email"
            maxLength={180}
            onChange={(event) => change("email", event.target.value)}
            type="email"
            value={form.email}
          />
          <span>Correo electrónico</span>
          {errors.email ? <small className="entity-field__error">{errors.email}</small> : null}
        </label>

        <label className={fieldClass(form.id_rol, errors.id_rol)}>
          <select
            disabled={saving || isSelf}
            onChange={(event) => change("id_rol", event.target.value)}
            value={form.id_rol}
          >
            <option value="">Seleccionar...</option>
            {roleOptions.map((role) => (
              <option key={role.id} value={role.id}>
                {role.nombre}
              </option>
            ))}
          </select>
          <span>Rol *</span>
          {errors.id_rol ? <small className="entity-field__error">{errors.id_rol}</small> : null}
        </label>

        <label className={`${fieldClass(form.contrasena, errors.contrasena)} entity-field--wide`}>
          <input
            autoComplete="new-password"
            disabled={saving}
            maxLength={255}
            onChange={(event) => change("contrasena", event.target.value)}
            type="password"
            value={form.contrasena}
          />
          <span>{editing ? "Nueva contraseña (opcional)" : "Contraseña temporal *"}</span>
          {errors.contrasena ? (
            <small className="entity-field__error">{errors.contrasena}</small>
          ) : null}
        </label>
      </div>

      <div className="user-form-note">
        {isSelf
          ? "Por seguridad, no podés cambiar tu propio rol desde esta sesión."
          : "Los permisos efectivos se obtienen del rol seleccionado. La contraseña debe tener al menos 12 caracteres."}
      </div>
    </CrudModal>
  );
}
