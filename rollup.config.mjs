  import typescript from '@rollup/plugin-typescript';

  // Compila src/ts/Game.ts in un unico modulo ES (modules/js/Game.js) caricato da BGA. Il file è generato:
  // non va modificato a mano, si rigenera con `npm run build:ts`.
  export default {
    input: 'src/ts/Game.ts',
    output: {
      file: 'modules/js/Game.js',
      format: 'es',
      sourcemap: false,
      inlineDynamicImports: true,
    },
    plugins: [
      typescript({
        tsconfig: './tsconfig.json',
        outDir: 'modules/js',
      }),
    ],
    // BGA cerca la classe `Game` per nome nel modulo: senza questa opzione rollup potrebbe scartare codice che
    // il framework usa solo a runtime e che nessun import richiama in modo esplicito.
    treeshake: false,
  };